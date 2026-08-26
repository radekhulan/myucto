import { api } from './client'
import { appendStepUpProof } from './epoSubmissions'
import type { EpoSigningCredential, EpoStepUpProof } from './epoSubmissions'

/** Typy zpráv pro kopii dodavateli — zrcadlí RecipientResolver::TYPE_*. */
export type SelfCopyType = 'documents' | 'reminders' | 'approvals'
/** off = neposílat, cc/bcc = role kopie dodavatele. */
export type SelfCopyMode = 'off' | 'cc' | 'bcc'

/** Náhled dopadu přepnutí accounting_mode → double_entry (audit 2026-07, G5). */
/** Polozka ciselniku CINNOSTI (CZ-NACE / c_okec) - naseptavac v danovem nastaveni. */
export interface NaceCode {
  /** Kanonicka hodnota do `c_okec` (sekce 01-09 vede ciselnik bez vodici nuly: 14800). */
  code: string
  /** Citelny zapis tridy - 731100 -> 73.11.00. */
  display: string
  name: string
  valid_from: string
}

/** Stav ulozeneho CZ-NACE proti ciselniku - `expired` po prechodu na NACE rev. 2.1. */
export interface NaceResolved {
  code: string
  display: string
  name: string | null
  status: 'active' | 'expired' | 'unknown'
  valid_to: string | null
}

export interface ModeSwitchPreview {
  cash_documents: number
  invoices: number
  purchase_invoices: number
  bank_transactions: number
  total: number
}

export type AiAssistScope = 'bank_tx' | 'purchase_invoices'

export interface AiAssistSettings {
  enabled: boolean
  scope: AiAssistScope[]
  provider: 'anthropic' | 'azure_openai' | 'openai' | 'gemini'
  provider_label: string
  data_region: 'eu' | 'us'
  dpa_confirmed: Record<string, string | null>
  embedding_available: boolean
  knn_warm: {
    bank_transaction: boolean
    purchase_invoice: boolean
    labels: { bank_transaction: number; purchase_invoice: number }
  }
  muted_sources: Array<{ source: 'knn' | 'llm'; muted_at: string; reason: Record<string, unknown> }>
  daily_limit: number
  today_used: number
}

export interface AiAssistUpdate {
  enabled?: boolean
  scope?: AiAssistScope[]
  dpa_confirm?: AiAssistSettings['provider']
  dpa_revoke?: AiAssistSettings['provider']
  unmute_source?: 'knn' | 'llm'
}

/** Řádek historie plátcovství DPH (supplier_vat_status_history, VH-01). */
export interface VatStatusHistoryEntry {
  id: number
  /** Baseline řádek má '1900-01-01' — smazat nejde. */
  effective_from: string
  is_vat_payer: boolean
  /** Identifikovaná osoba (§ 6g–6l ZDPH) — vylučuje se s is_vat_payer. */
  is_identified: boolean
  note: string | null
  annual_deduction_percent: number
}

/** Kolize retro-guardu (409 vat_status_locked_conflict). */
export interface VatStatusCollision {
  type: 'locked_period' | 'date_lock' | 'tax_submission'
  period_status?: string | null
  locked_until?: string | null
  form_code?: string
  period_year?: number
  period_month?: number | null
  period_quarter?: number | null
  submitted_at?: string | null
}

export interface VatStatusSavePayload {
  effective_from: string
  is_vat_payer: boolean
  is_identified: boolean
  note?: string | null
  /** true = uživatel potvrdil zápis přes kolize (409 flow). */
  acknowledge?: boolean
}

/** Hint VH-07: uložený řádek byl přechodem plátcovství s účinností <= dnes. */
export interface VatStatusS79Suggest {
  kind: 'registration' | 'deregistration'
  effective_on: string
}

export interface VatStatusState {
  vat_status_history: VatStatusHistoryEntry[]
  is_vat_payer: boolean
  is_identified: boolean
  suggest_s79?: VatStatusS79Suggest
}

/** § 6/§ 94 hlídač obratu (VH-07) — GET /settings/vat-status-history/registration-check. */
export interface VatRegistrationCheck {
  applicable: boolean
  year: number
  turnover: number
  limit_low: number
  limit_high: number
  status: 'below' | 'exceeded_low' | 'exceeded_high' | 'already_payer' | 'not_applicable'
  crossed_on: string | null
  becomes_payer_on: string | null
  is_vat_payer: boolean
  basis: string | null
  application_deadline: string | null
  /** 'statutory' = § 94/1 (10 pracovních dnů od překročení); 'informative' = den vzniku plátcovství. */
  application_deadline_basis: 'statutory' | 'informative' | null
}

export interface Supplier {
  id: number
  company_name: string
  display_name: string | null
  street: string
  city: string
  zip: string
  country_id: number
  country_iso?: string
  country_name_cs?: string
  country_name_en?: string
  ic: string | null
  dic: string | null
  is_vat_payer: boolean
  vat_status_effective_from?: string
  vat_status_history?: VatStatusHistoryEntry[]
  /** Identifikovaná osoba (§ 6g–6l ZDPH, issue #94) — neplátce v tuzemsku
   *  s přeshraničními povinnostmi. Nelze kombinovat s is_vat_payer. */
  is_identified: boolean
  email: string
  phone: string | null
  web: string | null
  tagline: string | null
  commercial_register: string | null
  default_currency_id: number
  default_currency: string
  default_vat_rate_id: number
  default_payment_due_days: number
  default_payment_due_unit: 'days' | 'month'
  /** Výchozí režim cen u nových faktur (false = bez DPH, true = ceny s DPH). */
  default_prices_include_vat: boolean
  default_hourly_rate: number
  auto_send_reminders: boolean
  reminder_days_after_due: number
  auto_generate_recurring: boolean
  embed_isdoc: boolean
  proforma_payment_document: 'final_on_full_payment' | 'always_tax_document'
  logo_path: string | null
  signature_path: string | null
  pohoda_account_code: string | null
  pohoda_centre_code: string | null
  pohoda_activity_code: string | null
  pohoda_contract_code: string | null
  /** Předkontace (migrace 1376) — prázdno = Pohoda si po importu dosadí vlastní default. */
  pohoda_accounting_code: string | null
  // Per-supplier konfigurace číslování faktur (migrace 0014).
  // *_format — template typu 'JD{YYYY}-{CC}', null = fallback na cfg.varsymbol.templates.{type}.
  // period — 'year' (1.1.) | 'month' (1. dne v měsíci) | 'none' (nikdy).
  invoice_number_format: string | null
  proforma_number_format: string | null
  credit_note_number_format: string | null
  // Šablona interního čísla přijaté faktury (migrace 0095). null = vestavěný
  // default '{PP}{YY}{MM}{CCC}'. {PP} = daňový prefix (PF/PN/KU/KN/NU/NN).
  purchase_invoice_number_format: string | null
  invoice_number_period: 'year' | 'month' | 'none'
  // Per-supplier email branding (migrace 0016)
  email_branding_enabled: boolean
  email_accent_color: string  // #RRGGBB
  pdf_logo_show_name: boolean // vedle loga v PDF zobrazit i název firmy (migrace 0058)
  branding_profiles_enabled: boolean
  has_email_logo?: boolean    // server flag (existence storage/supplier-logos/sup-{id}.png)
  // Děkovný e-mail za úhradu (issue #57)
  payment_thanks_enabled: boolean
  payment_thanks_auto_send: boolean
  payment_thanks_default_checked: boolean
  payment_thanks_attach_paid_pdf: boolean
  // Kopie odchozích e-mailů dodavateli (migrace 0102). Klíč chybí / objekt null
  // = typ jede dle cfg fallbacku (cfg_self_copy_fallback níže).
  self_copy?: Partial<Record<SelfCopyType, SelfCopyMode>> | null
  // Efektivní hodnoty z cfg (read-only) — UI je ukáže u volby „dle konfigurace".
  // `approvals` má v cfg dva flagy: žádost (approvals) a upomínka (approval_reminders).
  cfg_self_copy_fallback?: Record<SelfCopyType | 'approval_reminders', SelfCopyMode>
  // Režim účetnictví (Epic F0, migrace 1001) — double_entry aktivuje účetní moduly (F1)
  accounting_mode?: 'tax_evidence' | 'double_entry'
  // „Vést účetnictví" (migrace 1179) — opt-out účetní nadstavby v menu. Na licenci nemá vliv.
  accounting_enabled?: boolean
  // „Vést mzdy" (migrace 1187) — výchozí opt-in modulu. Na licenci zatím nemá vliv.
  payroll_enabled?: boolean
  // Skladová evidence (Epic SKLAD, migrace 1022) — nezávislé na accounting_mode.
  // Smí přepínat i účetní (ne jen admin) — viz SettingsAction::$stockOnlyFields.
  stock_enabled?: boolean
  stock_auto_issue?: boolean
  /** Od kterého stavu objednávky se zboží počítá „na cestě" (migrace 1331, rozhodnutí #2). */
  stock_in_transit_from?: 'sent' | 'confirmed'
  // Auto-post hook (A2, migrace 1035) — auto-zaúčtování FV po vystavení / PF po přijetí;
  // účinek jen v režimu double_entry.
  auto_post_invoices?: boolean
  auto_post_purchases?: boolean
  // Tax settings pro EPO výkazy DPH/KH (migrace 0038, fáze 6)
  taxpayer_type?: 'fo' | 'po' | null
  vat_period?: 'monthly' | 'quarterly' | null
  flat_tax_band?: 'none' | 'band1' | 'band2' | 'band3' | null
  oss_enabled?: boolean
  oss_valid_from?: string | null
  oss_valid_to?: string | null
  oss_identification_country?: string | null
  oss_return_currency?: string | null
  financial_office_code?: string | null
  workplace_code?: string | null
  cz_nace_code?: string | null
  /** Ulozeny CZ-NACE prelozeny pres ciselnik CINNOSTI (read-only, dopocitava backend). */
  cz_nace_resolved?: NaceResolved | null
  data_box_id?: string | null
  data_box_type?: 'FO' | 'PFO' | 'PO' | 'OVM' | null
  sest_jmeno?: string | null
  sest_prijmeni?: string | null
  sest_telefon?: string | null
  sest_email?: string | null
  sest_funkce?: string | null
  // Doplňky pro DPH/KH XML VetaP (migrace 0043)
  street_number_pop?: string | null
  street_number_orient?: string | null
  opr_jmeno?: string | null
  opr_prijmeni?: string | null
  opr_postaveni?: string | null
  // Přehled OSVČ pro ČSSZ XML (Fáze 3)
  cssz_vsdp?: string | null
  cssz_ossz_code?: string | null
  health_insurance_number?: string | null
  // AI extrakční brána (Epic F7) — non-secret volby (secrety jen přes /ai/credentials).
  ai_provider?: 'anthropic' | 'azure_openai' | 'openai' | 'gemini'
  ai_data_region?: 'eu' | 'us'
  ai_eu_residency_required?: boolean
  // Globální cfg fallback (read-only) — UI ho ukáže jako placeholder
  // v prázdných polích per-supplier šablon. Hodnota přichází z cfg.varsymbol.templates.
  cfg_varsymbol_fallback?: {
    invoice: string
    proforma: string
    credit_note: string
    purchase: string
  }
  // Featura G (private/REAL_data_followup_UX.md) — preventivní varování: efektivní
  // šablony (supplier-wide + per-client přepsání) numericky kolidují po normalizaci
  // VS na číslice (StatementMatcher) — read-only, přepočítá se při každém načtení/uložení.
  number_series_collisions?: NumberSeriesCollision[]
}

export interface NumberSeriesSide {
  type: 'invoice' | 'proforma' | 'credit_note'
  client_id: number | null
  client_name: string | null
  revenue_category_id: number | null
  revenue_category_name: string | null
  template: string
}

export interface NumberSeriesCollision {
  a: NumberSeriesSide
  b: NumberSeriesSide
}

export interface BrandingProfile {
  id: number
  supplier_id: number
  name: string
  display_name: string | null
  tagline: string | null
  email: string | null
  reply_to: string | null
  email_profile_id: number | null
  phone: string | null
  web: string | null
  email_footer: string | null
  logo_path: string | null
  accent_color: string
  branding_enabled: boolean
  pdf_logo_show_name: boolean
  is_active: boolean
  is_default: boolean
}

export interface CurrencyAccount {
  id: number
  code: string
  label: string
  symbol: string
  name_cs: string
  name_en: string
  decimals: number
  is_active: boolean
  is_default: boolean
  account_number: string | null
  bank_code: string | null
  bank_name: string | null
  iban: string | null
  bic: string | null
  invoices_count?: number
}

export interface BankEmailImapSettings {
  id: number | null
  supplier_id: number
  name: string
  enabled: boolean
  host: string
  port: number
  encryption: 'ssl' | 'tls' | 'none'
  validate_cert: boolean
  require_email_auth: boolean
  allow_forwarded: boolean
  forwarded_from: string | null
  email_auth_serv_id: string | null
  username: string
  folder: string
  max_messages_per_run: number
  process_from_date: string | null
  success_action: 'none' | 'add_flag' | 'move' | 'mark_seen'
  success_flag: string | null
  success_move_folder: string | null
  failure_action: 'none' | 'add_flag' | 'move'
  failure_flag: string | null
  failure_move_folder: string | null
  retry_failed: boolean
  max_attempts: number
  has_password: boolean
  last_scan_at?: string | null
  last_scan_status?: 'ok' | 'error' | null
  last_scan_message?: string | null
}

export interface BankEmailProvider {
  id: number | null
  provider_ref: string
  system?: boolean
  supplier_id: number | null
  code: string
  name: string
  parser_type: string
  enabled: boolean
  sender_whitelist: string | null
  subject_pattern: string | null
  body_pattern: string | null
  field_patterns: Record<string, string> | null
  normalizer_config: Record<string, unknown> | null
}

export interface BankEmailAccountMapping {
  id: number | null
  currency_id: number
  currency_code: string
  label: string
  account_number: string | null
  bank_code: string | null
  bank_name: string | null
  imap_account_id: number | null
  imap_account_name?: string | null
  provider_id: number | null
  provider_ref: string | null
  enabled: boolean
  amount_tolerance: number
  provider_code: string | null
  provider_name: string | null
}

export interface BankEmailProcessedMessage {
  id: number
  imap_account_id: number | null
  imap_account_name?: string | null
  imap_uid: number | null
  message_id: string | null
  fallback_hash: string
  message_date: string | null
  sender: string | null
  subject: string | null
  provider_code: string | null
  status: string
  /** Stav odvozený ze živého párování transakce (řeší zastaralý snapshot `status`). */
  effective_status?: string
  /** true = transakce je aktuálně spárovaná (i když `status` říká match_failed). */
  matched?: boolean
  /** Živý match_status navázané bank_transaction (auto_exact/auto_partial/manual/unmatched). */
  tx_match_status?: string | null
  parsed_payload: Record<string, any> | null
  bank_transaction_id: number | null
  matched_invoice_id: number | null
  matched_purchase_invoice_id?: number | null
  matched_varsymbol?: string | null
  error_message: string | null
  processed_at: string
}

export interface BankEmailOverview {
  imap: BankEmailImapSettings
  imap_accounts: BankEmailImapSettings[]
  providers: BankEmailProvider[]
  mappings: BankEmailAccountMapping[]
  messages: BankEmailProcessedMessage[]
  messages_total: number
}

export interface BankEmailMessagePage {
  items: BankEmailProcessedMessage[]
  total: number
  page: number
  limit: number
}

export interface VatRate {
  id: number
  code: string
  rate_percent: number
  country: string
  label_cs: string
  label_en: string
  is_default: boolean
  is_reverse_charge: boolean
  valid_from: string
  valid_to: string | null
  items_count?: number
}

export interface Country {
  id: number
  iso2: string
  iso3: string
  name_cs: string
  name_en: string
  is_eu: boolean
  uses_count?: number
}

export interface Unit {
  id: number
  code: string
  label_cs: string
  label_en: string
  is_default: boolean
  display_order: number
  items_count?: number
}

export interface PdfSigningDiagnostics {
  platform_enabled: boolean
  supplier_enabled: boolean
  effective_can_sign: boolean
  unavailable_reason: string | null
  failure_policy: string
  backend: {
    configured: string
    effective: string
    health: {
      ok: boolean
      message: string
    }
    capabilities: {
      supports_invisible: boolean
      supports_visible: boolean
      supports_append_signature_page: boolean
      supports_timestamp: boolean
      supports_pades: boolean
      requires_external_binary: boolean
      supported_certificate_types: string[]
    }
  }
  profile: {
    code: string
    available: boolean
    owner_type: string
    owner_id: number | null
    source: string
  }
  certificate: {
    configured: boolean
    exists: boolean
    storage: string
  }
  tsa: {
    configured: boolean
    auth_configured: boolean
  }
}

export interface SigningSettings {
  supplier_id: number
  accountant_profiles_enabled: boolean
}

export type SigningProfileUsage = 'pdf' | 'email_smime'

export interface SigningProfile {
  id: number
  supplier_id: number
  owner_user_id: number | null
  name: string
  code: string
  allowed_usages: SigningProfileUsage[]
  default_backend: string
  pdf_tsa_url: string | null
  pdf_tsa_username: string | null
  has_pdf_tsa_password: boolean
  pdf_reason: string | null
  has_certificate?: boolean
  certificate_subject?: string | null
  certificate_email?: string | null
  certificate_valid_from?: string | null
  certificate_valid_to?: string | null
  certificate_is_active?: boolean
  is_active: boolean
  created_by: number | null
  created_at: string
  updated_at: string
  deleted_at: string | null
}

export interface SigningProfilePayload {
  owner_user_id?: number | null
  name: string
  code: string
  allowed_usages: SigningProfileUsage[]
  default_backend: string
  pdf_tsa_enabled?: boolean
  pdf_tsa_url?: string | null
  pdf_tsa_username?: string | null
  pdf_tsa_password?: string | null
  pdf_reason?: string | null
  is_active?: boolean
}

export interface EmailProfile {
  id: number
  supplier_id: number
  name: string
  code: string
  from_email: string
  from_name: string | null
  reply_to_email: string | null
  reply_to_name: string | null
  reply_to_enabled: boolean
  signing_profile_id: number | null
  signing_profile_name: string | null
  signing_profile_code: string | null
  dkim_domain: string | null
  dkim_selector: string | null
  dkim_enabled: boolean
  transport_type: 'global' | 'smtp' | 'sendmail'
  smtp_host: string | null
  smtp_port: number | null
  smtp_encryption: 'none' | 'tls' | 'ssl'
  smtp_auth_enabled: boolean
  smtp_auth_type: 'LOGIN' | 'PLAIN' | 'CRAM-MD5' | 'XOAUTH2'
  smtp_username: string | null
  has_smtp_password: boolean
  smtp_verify_peer: boolean
  smtp_verify_peer_name: boolean
  smtp_allow_self_signed: boolean
  smtp_timeout: number | null
  smtp_keepalive: boolean
  sendmail_command: string | null
  imap_sent_enabled: boolean
  imap_host: string | null
  imap_port: number | null
  imap_encryption: 'none' | 'tls' | 'ssl'
  imap_validate_cert: boolean
  imap_username: string | null
  has_imap_password: boolean
  imap_folder: string | null
  imap_create_folder: boolean
  imap_mark_seen: boolean
  imap_timeout: number
  imap_on_failure: 'log_only' | 'fail_send'
  is_default: boolean
  is_active: boolean
  created_by: number | null
  created_at: string
  updated_at: string
  deleted_at: string | null
}

export interface EmailProfilePayload {
  name: string
  code: string
  from_email: string
  from_name?: string | null
  reply_to_email?: string | null
  reply_to_name?: string | null
  reply_to_enabled?: boolean
  signing_profile_id?: number | null
  dkim_domain?: string | null
  dkim_selector?: string | null
  dkim_enabled?: boolean
  transport_type?: 'global' | 'smtp' | 'sendmail'
  smtp_host?: string | null
  smtp_port?: number | null
  smtp_encryption?: 'none' | 'tls' | 'ssl'
  smtp_auth_enabled?: boolean
  smtp_auth_type?: 'LOGIN' | 'PLAIN' | 'CRAM-MD5' | 'XOAUTH2'
  smtp_username?: string | null
  smtp_password?: string | null
  smtp_verify_peer?: boolean
  smtp_verify_peer_name?: boolean
  smtp_allow_self_signed?: boolean
  smtp_timeout?: number | null
  smtp_keepalive?: boolean
  sendmail_command?: string | null
  imap_sent_enabled?: boolean
  imap_host?: string | null
  imap_port?: number | null
  imap_encryption?: 'none' | 'tls' | 'ssl'
  imap_validate_cert?: boolean
  imap_username?: string | null
  imap_password?: string | null
  imap_folder?: string | null
  imap_create_folder?: boolean
  imap_mark_seen?: boolean
  imap_timeout?: number | null
  imap_on_failure?: 'log_only' | 'fail_send'
  is_default?: boolean
  is_active?: boolean
}

export interface EmailProfileImapAppendResult {
  status: 'skipped' | 'saved' | 'failed'
  folder: string | null
  error: string | null
}

export interface EmailProfileImapFoldersResult {
  ok: boolean
  message: string
  folders?: EmailProfileImapFolder[]
}

export interface EmailProfileImapFolder {
  path: string
  full_name: string
  name: string
  delimiter: string
  writable: boolean
  system: boolean
  sent: boolean
  no_select: boolean
  has_children: boolean
}

export interface EmailProfileTestResult {
  sent_to: string[]
  sent_at: string
  smtp_response: string
  imap_append?: EmailProfileImapAppendResult
  is_test: boolean
  is_draft?: boolean
}

/**
 * Trezor certifikátů pod neutrálním jménem — `/settings/certificates` míří do
 * téhož úložiště jako `/reports/submissions/epo-credentials`, jen bez EPO
 * nálepky, protože „EPO certifikát" je jméno prvního konzumenta, ne účelu.
 * Soukromý klíč se nikdy nedotkne bez čerstvého ověření, takže se posílá stejný
 * step-up proof jako u EPO (passkey token / TOTP / heslo).
 */
export type CertificateVaultItem = EpoSigningCredential

export interface CertificateVaultUploadPayload {
  file: File
  /** Prázdný label backend nahradí jménem souboru. */
  label: string
  /** Heslo k PFX/P12, ne heslo do aplikace. */
  password: string
  proof: EpoStepUpProof
}

export type SigningCredentialPassphrasePolicy = 'encrypted_store' | 'passphrase_file' | 'prompt_on_use'

export interface SigningProfileCredentialMeta {
  has_certificate: boolean
  certificate_source?: 'personal_vault' | 'uploaded_file'
  vault_credential_id?: number | null
  certificate_fingerprint?: string | null
  certificate_subject?: string | null
  certificate_email?: string | null
  certificate_valid_from?: string | null
  certificate_valid_to?: string | null
  certificate_usage?: Record<string, unknown>
  passphrase_policy?: SigningCredentialPassphrasePolicy | null
  passphrase_profile_id?: string | null
  is_active?: boolean
  expired?: boolean
}

export interface SigningProfileCredentialPassphrasePayload {
  passphrase_policy: SigningCredentialPassphrasePolicy
  passphrase_profile_id?: string | null
  password?: string | null
}

export type PdfSignatureSelectionSource = 'logged_in_user' | 'admin_profile_settings'
export type PdfSignatureUserProfileFallback = 'admin_profile_settings' | 'fail_closed' | 'fallback_unsigned'
export type PdfSignatureFailurePolicy = 'fallback_unsigned' | 'fail_closed' | 'skip_when_unconfigured'

export interface PdfSignatureOutputSetting {
  supplier_id: number
  usage: SigningProfileUsage
  output_type: string
  enabled: boolean
  backend: string
  selection_source: PdfSignatureSelectionSource
  user_profile_fallback: PdfSignatureUserProfileFallback
  default_profile_id: number | null
  failure_policy: PdfSignatureFailurePolicy
  signature_config: Record<string, unknown>
}

export interface PdfSignatureSettings {
  output_types: string[]
  output_settings: PdfSignatureOutputSetting[]
}

export interface PdfSignatureUserDefault {
  supplier_id: number
  usage: SigningProfileUsage
  output_type: string
  user_id: number
  profile_id: number
}

export interface PdfSignatureUserDefaults {
  output_types: string[]
  user_defaults: PdfSignatureUserDefault[]
  output_settings: PdfSignatureOutputSetting[]
}

export type PdfSignatureDocumentEntityType = 'invoice' | 'work_report'
export type PdfSignatureDocumentSelectionSource = PdfSignatureSelectionSource | 'inherit'

export interface PdfSignatureDocumentSelection {
  usage: SigningProfileUsage
  entity_type: PdfSignatureDocumentEntityType
  entity_id: number
  selection_source: PdfSignatureDocumentSelectionSource
  admin_profile_id: number | null
  inherited_selection_source: PdfSignatureSelectionSource
  inherited_admin_profile_id: number | null
  effective_selection_source: PdfSignatureSelectionSource
  effective_admin_profile_id: number | null
  has_override: boolean
  effective_will_sign: boolean
}

export interface PdfSignatureTestResult {
  output_type: string
  status: 'signed' | 'skipped' | 'failed' | 'fallback_unsigned'
  backend: string
  profile_code: string | null
  certificate_cn: string | null
  level: string | null
  timestamped: boolean
  failure_policy: PdfSignatureFailurePolicy
  reason?: string
  error?: string
}

export type PdfSignatureOutputSettingPayload = Partial<Pick<
  PdfSignatureOutputSetting,
  'enabled' | 'backend' | 'selection_source' | 'user_profile_fallback' | 'default_profile_id' | 'failure_policy' | 'signature_config'
>>

export interface PdfSignatureOutputSettingsBatchResult {
  output_settings: PdfSignatureOutputSetting[]
}

export const settingsApi = {
  getSupplier: () => api.get<Supplier>('/settings/supplier').then(r => r.data),
  updateSupplier: (payload: Partial<Supplier>) => api.put<Supplier>('/settings/supplier', payload).then(r => r.data),
  // E2: při total > 0 přesměruje FE do průvodce; přímé uložení jistí BE 409 backfill_required.
  getModeSwitchPreview: () => api.get<ModeSwitchPreview>('/settings/mode-switch-preview').then(r => r.data),

  // Historie plátcovství DPH (VH-01) — seznam vrací GET /settings/supplier
  // (vat_status_history); zápis/mazání vrací čerstvou historii + živé flagy.
  saveVatStatus: (payload: VatStatusSavePayload) =>
    api.post<VatStatusState>('/settings/vat-status-history', payload).then(r => r.data),
  deleteVatStatus: (id: number, acknowledge = false) =>
    api.delete<VatStatusState>(`/settings/vat-status-history/${id}`, {
      params: acknowledge ? { acknowledge: 1 } : {},
    }).then(r => r.data),
  // § 6/§ 94 hlídač obratu (VH-07) — banner „obrat překročen" v bloku Plátcovství DPH.
  getVatRegistrationCheck: () =>
    api.get<VatRegistrationCheck>('/settings/vat-status-history/registration-check').then(r => r.data),
  getAiAssist: () => api.get<AiAssistSettings>('/settings/ai-assist').then(r => r.data),
  updateAiAssist: (payload: AiAssistUpdate) => api.put<AiAssistSettings>('/settings/ai-assist', payload).then(r => r.data),

  listCurrencies: () => api.get<CurrencyAccount[]>('/settings/currencies').then(r => r.data),
  createCurrency: (payload: Partial<CurrencyAccount>) =>
    api.post<{ id: number; code: string }>('/settings/currencies', payload).then(r => r.data),
  updateCurrency: (id: number, payload: Partial<CurrencyAccount>) =>
    api.put<CurrencyAccount>(`/settings/currencies/${id}`, payload).then(r => r.data),
  deleteCurrency: (id: number) => api.delete(`/settings/currencies/${id}`).then(r => r.data),

  getBankEmailOverview: () =>
    api.get<BankEmailOverview>('/settings/bank-email-notices').then(r => r.data),
  updateBankEmailImap: (payload: Partial<BankEmailImapSettings> & { password?: string }) =>
    api.put<BankEmailImapSettings>('/settings/bank-email-notices/imap', payload).then(r => r.data),
  testBankEmailImap: () =>
    api.post<{ ok: boolean; message: string; folders?: string[] }>('/settings/bank-email-notices/imap/test', {}).then(r => r.data),
  createBankEmailImapAccount: (payload: Partial<BankEmailImapSettings> & { password?: string }) =>
    api.post<BankEmailImapSettings>('/settings/bank-email-notices/imap-accounts', payload).then(r => r.data),
  updateBankEmailImapAccount: (id: number, payload: Partial<BankEmailImapSettings> & { password?: string }) =>
    api.put<BankEmailImapSettings>(`/settings/bank-email-notices/imap-accounts/${id}`, payload).then(r => r.data),
  deleteBankEmailImapAccount: (id: number) =>
    api.delete<{ deleted: boolean }>(`/settings/bank-email-notices/imap-accounts/${id}`).then(r => r.data),
  testBankEmailImapAccount: (id: number) =>
    api.post<{ ok: boolean; message: string; folders?: string[] }>(`/settings/bank-email-notices/imap-accounts/${id}/test`, {}).then(r => r.data),
  browseBankEmailImapFolders: (payload: Partial<BankEmailImapSettings> & { password?: string }, id?: number | null) =>
    api.post<{ ok: boolean; message: string; folders?: string[] }>(
      id ? `/settings/bank-email-notices/imap-accounts/${id}/folders` : '/settings/bank-email-notices/imap-accounts/folders',
      payload,
    ).then(r => r.data),
  createBankEmailProvider: (payload: Partial<BankEmailProvider>) =>
    api.post<BankEmailProvider>('/settings/bank-email-notices/providers', payload).then(r => r.data),
  updateBankEmailProvider: (id: number, payload: Partial<BankEmailProvider>) =>
    api.put<BankEmailProvider>(`/settings/bank-email-notices/providers/${id}`, payload).then(r => r.data),
  deleteBankEmailProvider: (id: number) =>
    api.delete<{ deleted: boolean }>(`/settings/bank-email-notices/providers/${id}`).then(r => r.data),
  updateBankEmailMappings: (mappings: Partial<BankEmailAccountMapping>[]) =>
    api.put<BankEmailAccountMapping[]>('/settings/bank-email-notices/mappings', { mappings }).then(r => r.data),
  testBankEmailParser: (payload: { provider_ref?: string | null; sender?: string; subject?: string; text: string }) =>
    api.post<{ provider: Pick<BankEmailProvider, 'id' | 'code' | 'name' | 'parser_type' | 'provider_ref'>; parsed: Record<string, any> }>(
      '/settings/bank-email-notices/parser/test',
      payload,
    ).then(r => r.data),
  scanBankEmailNotices: (limit?: number) =>
    api.post<Record<string, any>>('/settings/bank-email-notices/scan', limit ? { limit } : {}).then(r => r.data),
  listBankEmailMessages: (page = 1) =>
    api.get<BankEmailMessagePage>('/settings/bank-email-notices/messages', { params: { page } }).then(r => r.data),
  deleteBankEmailMessage: (id: number) =>
    api.delete<{ deleted: boolean }>(`/settings/bank-email-notices/messages/${id}`).then(r => r.data),

  listVatRates:   () => api.get<VatRate[]>('/settings/vat-rates').then(r => r.data),
  createVatRate:  (p: Partial<VatRate>) => api.post('/settings/vat-rates', p).then(r => r.data),
  updateVatRate:  (id: number, p: Partial<VatRate>) => api.put(`/settings/vat-rates/${id}`, p).then(r => r.data),
  deleteVatRate:  (id: number) => api.delete(`/settings/vat-rates/${id}`).then(r => r.data),

  listCountries:  () => api.get<Country[]>('/settings/countries').then(r => r.data),
  createCountry:  (p: Partial<Country>) => api.post('/settings/countries', p).then(r => r.data),
  updateCountry:  (id: number, p: Partial<Country>) => api.put(`/settings/countries/${id}`, p).then(r => r.data),
  deleteCountry:  (id: number) => api.delete(`/settings/countries/${id}`).then(r => r.data),

  listUnits:  () => api.get<Unit[]>('/settings/units').then(r => r.data),
  createUnit: (p: Partial<Unit>) => api.post('/settings/units', p).then(r => r.data),
  updateUnit: (id: number, p: Partial<Unit>) => api.put(`/settings/units/${id}`, p).then(r => r.data),
  deleteUnit: (id: number) => api.delete(`/settings/units/${id}`).then(r => r.data),

  // Email branding (M16)
  listEmailProfiles: () =>
    api.get<EmailProfile[]>('/settings/email-profiles').then(r => r.data),
  createEmailProfile: (payload: EmailProfilePayload) =>
    api.post<EmailProfile>('/settings/email-profiles', payload).then(r => r.data),
  updateEmailProfile: (id: number, payload: Partial<EmailProfilePayload>) =>
    api.put<EmailProfile>(`/settings/email-profiles/${id}`, payload).then(r => r.data),
  testEmailProfile: (id: number) =>
    api.post<EmailProfileTestResult>(`/settings/email-profiles/${id}/test`, {}).then(r => r.data),
  testEmailProfileDraft: (payload: EmailProfilePayload, id?: number | null) =>
    api.post<EmailProfileTestResult>('/settings/email-profiles/test', id ? { ...payload, id } : payload).then(r => r.data),
  testEmailProfileImapSettings: (payload: Partial<EmailProfilePayload>, id?: number | null) =>
    api.post<EmailProfileImapFoldersResult>(
      id ? `/settings/email-profiles/${id}/imap-test` : '/settings/email-profiles/imap-test',
      payload,
    ).then(r => r.data),
  browseEmailProfileImapFolders: (payload: Partial<EmailProfilePayload>, id?: number | null) =>
    api.post<EmailProfileImapFoldersResult>(
      id ? `/settings/email-profiles/${id}/folders` : '/settings/email-profiles/folders',
      payload,
    ).then(r => r.data),
  deleteEmailProfile: (id: number) =>
    api.delete<{ deleted: boolean }>(`/settings/email-profiles/${id}`).then(r => r.data),

  uploadEmailLogo: (file: File) => {
    const fd = new FormData()
    fd.append('file', file)
    return api.post<{ logo_path: string; width: number; height: number }>(
      '/settings/email-branding/logo',
      fd,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    ).then(r => r.data)
  },
  deleteEmailLogo: () => api.delete('/settings/email-branding/logo').then(r => r.data),

  listBrandingProfiles: () =>
    api.get<BrandingProfile[]>('/settings/branding-profiles').then(r => r.data),
  createBrandingProfile: (payload: Partial<BrandingProfile>) =>
    api.post<BrandingProfile>('/settings/branding-profiles', payload).then(r => r.data),
  updateBrandingProfile: (id: number, payload: Partial<BrandingProfile>) =>
    api.put<BrandingProfile>(`/settings/branding-profiles/${id}`, payload).then(r => r.data),
  deleteBrandingProfile: (id: number) =>
    api.delete<{ deleted: boolean }>(`/settings/branding-profiles/${id}`).then(r => r.data),
  setDefaultBrandingProfile: (id: number) =>
    api.post<BrandingProfile>(`/settings/branding-profiles/${id}/default`, {}).then(r => r.data),
  uploadBrandingProfileLogo: (id: number, file: File) => {
    const fd = new FormData()
    fd.append('file', file)
    return api.post<BrandingProfile>(`/settings/branding-profiles/${id}/logo`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  deleteBrandingProfileLogo: (id: number) =>
    api.delete<BrandingProfile>(`/settings/branding-profiles/${id}/logo`).then(r => r.data),

  getPdfSigningDiagnostics: () =>
    api.get<PdfSigningDiagnostics>('/settings/pdf-signing/diagnostics').then(r => r.data),
  listCertificates: () =>
    api.get<{ items: CertificateVaultItem[] }>('/settings/certificates').then(r => r.data.items),
  uploadCertificate: (payload: CertificateVaultUploadPayload) => {
    const data = new FormData()
    data.append('file', payload.file, payload.file.name)
    data.append('label', payload.label)
    data.append('password', payload.password)
    appendStepUpProof(data, payload.proof)
    return api.post<CertificateVaultItem>('/settings/certificates', data, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  getSigningSettings: () =>
    api.get<SigningSettings>('/settings/signing').then(r => r.data),
  updateSigningSettings: (payload: Pick<SigningSettings, 'accountant_profiles_enabled'>) =>
    api.put<SigningSettings>('/settings/signing', payload).then(r => r.data),
  listSigningProfiles: () =>
    api.get<SigningProfile[]>('/settings/signing/profiles').then(r => r.data),
  createSigningProfile: (payload: SigningProfilePayload) =>
    api.post<SigningProfile>('/settings/signing/profiles', payload).then(r => r.data),
  updateSigningProfile: (id: number, payload: Partial<SigningProfilePayload>) =>
    api.put<SigningProfile>(`/settings/signing/profiles/${id}`, payload).then(r => r.data),
  deleteSigningProfile: (id: number) =>
    api.delete<{ deleted: boolean }>(`/settings/signing/profiles/${id}`).then(r => r.data),
  getSigningProfileCredential: (id: number) =>
    api.get<SigningProfileCredentialMeta>(`/settings/signing/profiles/${id}/credentials/certificate`).then(r => r.data),
  uploadSigningProfileCredential: (
    id: number,
    file: File,
    password: string,
    passphrasePolicy: SigningCredentialPassphrasePolicy,
    passphraseProfileId: string | null,
  ) => {
    const fd = new FormData()
    fd.append('file', file)
    fd.append('password', password)
    fd.append('passphrase_policy', passphrasePolicy)
    if (passphraseProfileId) fd.append('passphrase_profile_id', passphraseProfileId)
    return api.post<SigningProfileCredentialMeta>(
      `/settings/signing/profiles/${id}/credentials/certificate`,
      fd,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    ).then(r => r.data)
  },
  updateSigningProfileCredentialPassphrase: (
    id: number,
    payload: SigningProfileCredentialPassphrasePayload,
  ) =>
    api.put<SigningProfileCredentialMeta>(
      `/settings/signing/profiles/${id}/credentials/certificate`,
      payload,
    ).then(r => r.data),
  deleteSigningProfileCredential: (id: number) =>
    api.delete<SigningProfileCredentialMeta>(`/settings/signing/profiles/${id}/credentials/certificate`).then(r => r.data),
  listPersonalSigningCertificates: () =>
    api.get<EpoSigningCredential[]>('/settings/signing/personal-certificates').then(r => r.data),
  linkPersonalSigningCertificate: (
    profileId: number,
    credentialId: number,
    password: string,
    totpCode: string,
  ) => api.put<SigningProfileCredentialMeta>(
    `/settings/signing/profiles/${profileId}/credentials/personal-vault`,
    {
      credential_id: credentialId,
      password,
      totp_code: totpCode || undefined,
    },
  ).then(r => r.data),
  getPdfSigningSettings: () =>
    api.get<PdfSignatureSettings>('/settings/pdf-signing').then(r => r.data),
  testPdfSigning: (outputType: string) =>
    api.post<PdfSignatureTestResult>('/settings/pdf-signing/test', { output_type: outputType }).then(r => r.data),
  updatePdfSignatureOutputSetting: (outputType: string, payload: PdfSignatureOutputSettingPayload) =>
    api.put<PdfSignatureOutputSetting>(`/settings/pdf-signing/output-settings/${outputType}`, payload).then(r => r.data),
  updatePdfSignatureOutputSettings: (settings: Array<PdfSignatureOutputSettingPayload & { output_type: string }>) =>
    api.put<PdfSignatureOutputSettingsBatchResult>('/settings/pdf-signing/output-settings', { settings }).then(r => r.data),
  getPdfSigningUserDefaults: () =>
    api.get<PdfSignatureUserDefaults>('/settings/pdf-signing/user-defaults').then(r => r.data),
  updatePdfSigningUserDefault: (outputType: string, profileId: number | null) =>
    api.put<PdfSignatureUserDefault | null>(`/settings/pdf-signing/user-defaults/${outputType}`, {
      profile_id: profileId,
    }).then(r => r.data),
  getPdfSignatureDocumentSelection: (entityType: PdfSignatureDocumentEntityType, id: number) =>
    api.get<PdfSignatureDocumentSelection>(`/documents/${entityType}/${id}/signature-selection`).then(r => r.data),
  updatePdfSignatureDocumentSelection: (
    entityType: PdfSignatureDocumentEntityType,
    id: number,
    payload: { selection_source: PdfSignatureDocumentSelectionSource; admin_profile_id?: number | null },
  ) =>
    api.put<PdfSignatureDocumentSelection>(`/documents/${entityType}/${id}/signature-selection`, payload).then(r => r.data),
  deletePdfSignatureDocumentSelection: (entityType: PdfSignatureDocumentEntityType, id: number) =>
    api.delete<PdfSignatureDocumentSelection>(`/documents/${entityType}/${id}/signature-selection`).then(r => r.data),
  /**
   * Naseptavac CZ-NACE - vraci jen kody platne k dnesku. ARES eviduje jeste
   * NACE rev. 2, ciselnik EPO je od 1. 1. 2026 na rev. 2.1, takze prefill
   * z ARES casto prinese expirovany kod a uzivatel si tu najde nastupce.
   * Prazdny `q` vrati prvni stranku; jinak prefix kodu nebo hledani v nazvu.
   */
  searchNaceCodes: (q: string, limit = 20) =>
    api.get<{ items: NaceCode[] }>('/settings/nace-codes', { params: { q, limit } }).then(r => r.data.items),

  // Vrací HTML string — frontend ho pak nacpe do iframe.srcdoc (obejde X-Frame-Options DENY).
  emailPreviewHtml: (locale: 'cs' | 'en' = 'cs', brandingProfileId: number | null = null) =>
    api.get<string>('/settings/email-branding/preview', {
      params: { locale, ...(brandingProfileId !== null ? { branding_profile_id: brandingProfileId } : {}) },
      responseType: 'text',
      transformResponse: [(d) => d],
    }).then(r => r.data),
}
