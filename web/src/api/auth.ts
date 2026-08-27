import { api, setCsrfToken } from './client'
import type { LicenseSummary } from './license'

export interface User {
  id: number
  email: string
  name: string
  role: RoleSummary
  is_superadmin: boolean
  locale: 'cs' | 'en'
  totp_enabled?: boolean
  must_setup_totp?: boolean
  mfa_enabled?: boolean
  mfa_methods?: Array<'passkey' | 'totp'>
  passkey_count?: number
  must_setup_mfa?: boolean
  /**
   * Protipól `must_setup_mfa` pro instalace, kde se MFA nevynucuje: server nabízí
   * zapnutí účtu bez jediného faktoru, dokud uživatel nabídku neodmítne. Nabídku
   * lze přeskočit — na rozdíl od `must_setup_mfa` nic neblokuje.
   */
  should_offer_mfa?: boolean
}

export interface RoleSummary {
  id: number
  name: string
  type: 'superadmin' | 'staff' | 'client'
  is_active?: boolean
  system_key?: string | null
}

export interface SupplierBrief {
  id: number
  company_name: string
  ic: string | null
  /** Identifikace firmy pro hlavičku Přehledu (IČ · DIČ · adresa na jednom řádku). */
  dic?: string | null
  street?: string
  city?: string
  zip?: string
  is_vat_payer: boolean
  /** Identifikovaná osoba (§ 6g–6l ZDPH, issue #94) — neplátce v tuzemsku
   *  s přeshraničními povinnostmi (RC faktury do EU, SHV, samovyměření). */
  is_identified: boolean
  /** Dodavatel je registrovaný v režimu One Stop Shop. */
  oss_enabled: boolean
  /** 'fo' = OSVČ (fyzická osoba), 'po' = s.r.o. (právnická osoba), null = nenastaveno. */
  taxpayer_type: 'fo' | 'po' | null
  default_payment_due_days: number
  default_payment_due_unit: 'days' | 'month'
  /** Výchozí režim cen u nových faktur (false = bez DPH, true = ceny s DPH). */
  default_prices_include_vat: boolean
  /** Posílá dodavatel automatické upomínky? Když ne, per-faktura přepínač se v editoru skryje. */
  auto_send_reminders: boolean
  /** Děkovný e-mail za úhradu (issue #57) — řídí checkbox v mark-paid modalu. */
  payment_thanks_enabled: boolean
  payment_thanks_default_checked: boolean
  /** Režim účetnictví (Epic F1). 'double_entry' zpřístupní účetní UI (deník, osnova, období). */
  accounting_mode?: 'tax_evidence' | 'double_entry'
  /** Skladová evidence (Epic SKLAD) — gate sekce Sklad v menu + editorů. Funguje nezávisle na accounting_mode. */
  stock_enabled?: boolean
  /**
   * „Vést účetnictví" (migrace 1179) — firemní opt-out účetní nadstavby. `false` schová
   * účetní sekce z menu stejně jako chybějící licence; fakturace, DPH a sklad zůstávají.
   * `undefined` = zapnuto (starší backend bez pole). Na licenci nemá vliv.
   */
  accounting_enabled?: boolean
  /** „Vést mzdy" — výchozí true; false skryje mzdový modul pro tuto firmu. */
  payroll_enabled?: boolean
}

export interface SetupStatus {
  needs_setup: boolean
  version: string
  /**
   * Spravovaná instalace — konfiguraci drží provozovatel, ne uživatel. Zamyká
   * self-update, adresu aplikace, vlastní SMTP transport, skenování adresářů
   * na serveru a vlastní domény. Backend to vynucuje sám; UI podle toho jen
   * POJMENUJE, proč akce chybí. `undefined` = starší backend bez pole.
   */
  managed?: boolean
  demo?: {
    enabled: boolean
    auto_login: boolean
    email: string
    password: string
  }
  passwordless_login_enabled: boolean
  captcha: {
    provider: 'turnstile' | 'none'
    site_key: string
    script_url: string
  }
}

export interface DomainContext {
  mode: 'canonical' | 'custom'
  hostname: string
  origin: string
  locked: boolean
  supplier_id: number | null
  purpose: 'portal' | 'public_links' | 'all' | null
  /** Vlastní domény jsou opt-in v cfg.php; vypnuté = správa domén se nenabízí. */
  feature_enabled?: boolean
  canonical_base_url?: string
  canonical_login_url?: string
}

export interface LoginPayload {
  email: string
  password: string
  totp?: string
  email_otp?: string
  /** Záložní jednorázový kód — break-glass při ztraceném silném faktoru. */
  recovery_code?: string
  remember_device?: boolean
  resend_otp?: boolean
  cf_turnstile_response?: string
}

/** Stav sady záložních kódů. Kódy samotné server podruhé nevydá. */
export interface RecoveryCodeStatus {
  total: number
  remaining: number
  generated_at: string | null
  low: boolean
  batch_size: number
}

export interface WebAuthnFlow {
  flow_token: string
  public_key: Record<string, any>
}

export interface SessionState {
  session_state: 'active' | 'locked'
  csrf_token: string
  server_time: string
  idle_expires_at: string | null
  lock_after_minutes: number
  unlock_methods: Array<'passkey'>
  user: User
}

export interface SessionLockPreference {
  user_lock_after_minutes: number | null
  admin_lock_after_minutes: number
  maximum_lock_after_minutes: number
  effective_lock_after_minutes: number
}

export interface SessionLockPreferenceUpdate extends SessionLockPreference {
  session: SessionState
}

export interface AuthSessionContract {
  user: User
  csrf_token: string
  require_totp: boolean
  require_mfa: boolean
  allowed_mfa_methods: Array<'passkey' | 'totp'>
  session_state: 'active'
  server_time: string
  idle_expires_at: string | null
  lock_after_minutes: number
}

export interface MeResponse extends AuthSessionContract {
  current_supplier_id: number
  suppliers: SupplierBrief[]
  permissions: Record<string, 0 | 1 | 2>
  permission_catalog_version: string
  license?: LicenseSummary
  domain_context?: DomainContext | null
}

export interface DomainLoginStart {
  request_token: string
  state: string
  login_url: string
  expires_in: number
}

export interface PasskeyCredential {
  id: number
  label: string
  transports: string[]
  backup_eligible: boolean
  backup_state: boolean
  created_at: string | null
  last_used_at: string | null
}

export interface MfaSetupCompletion {
  csrf_token?: string
  session_state?: 'active'
  must_setup_mfa?: false
}

export interface PasskeyRegistrationResult extends MfaSetupCompletion {
  credential: PasskeyCredential
  /**
   * První sada záložních kódů — chodí PRÁVĚ JEDNOU a jen tomu, kdo ještě žádné
   * použitelné neměl. Server plaintext neukládá, takže kdo je hned nezobrazí,
   * už je nikde nedohledá.
   */
  recovery_codes?: string[]
}

export interface TotpSetup {
  secret: string
  uri: string
  qr_data_uri: string
  issuer: string
}

export interface SetupPayload {
  admin: { name: string; email: string; password: string }
  /** Přijetí licenčního ujednání a obchodních podmínek — backend vyžaduje `true`. */
  terms_accepted: boolean
  require_mfa?: boolean
  allowed_mfa_methods?: Array<'passkey' | 'totp'>
  /** Legacy kompatibilita pro starší backendy/klienty. */
  require_totp?: boolean
  supplier?: {
    company_name: string
    display_name?: string
    street: string
    city: string
    zip: string
    country_iso2?: string
    ic?: string
    dic?: string
    is_vat_payer?: boolean
    email: string
    phone?: string
    web?: string
    commercial_register?: string
    taxpayer_type?: 'fo' | 'po'
    default_currency?: string
    default_payment_due_days?: number
    default_hourly_rate?: number
    bank_account?: {
      currency: string
      account_number?: string
      bank_code?: string
      bank_name?: string
      iban?: string
      bic?: string
    }
  }
}

export interface SetupSampleResult {
  clients: number
  projects: number
  invoices: number
  credit_notes: number
  vendors: number
  purchase_invoices: number
  recurring: number
  cars: number
  trips: number
  fuelings: number
  cash_registers: number
  cash_documents: number
  accounting_enabled: boolean
  stock_items: number
  stock_documents: number
  manufacturers: number
  eshop_categories: number
  assets: number
  bank_statements: number
  bank_transactions: number
  journal_entries: number
  warnings: string[]
}

export const authApi = {
  domainContext: () => api.get<DomainContext>('/auth/domain-context').then((r) => r.data),

  domainLoginStart: (codeChallenge: string, returnPath: string, handoffPath?: string) =>
    api.post<DomainLoginStart>('/auth/domain-login/start', {
      code_challenge: codeChallenge,
      return_path: returnPath,
      handoff_path: handoffPath || undefined,
    }).then((r) => r.data),

  domainLoginAuthorize: (requestToken: string, state: string) =>
    api.post<{ redirect_url: string }>('/auth/domain-login/authorize', {
      request_token: requestToken,
      state,
    }).then((r) => r.data),

  domainLoginExchange: (requestToken: string, code: string, state: string, codeVerifier: string) =>
    api.post<{ csrf_token: string; return_path: string; supplier_id: number }>('/auth/domain-login/exchange', {
      request_token: requestToken,
      code,
      state,
      code_verifier: codeVerifier,
    }).then((r) => r.data),

  setupStatus: () => api.get<SetupStatus>('/auth/setup-status').then((r) => r.data),

  setup: (payload: SetupPayload) =>
    api.post<{
      user: User
      next: string
      csrf_token: string
      require_totp: boolean
      require_mfa: boolean
      allowed_mfa_methods: Array<'passkey' | 'totp'>
      cfg_local_written: boolean
    }>(
      '/auth/setup',
      payload,
    ).then(r => r.data),

  /** ARES lookup pro setup wizard (funguje jen když ještě nemáme admin usera). */
  setupAresLookup: (ic: string) =>
    api.post<import('./clients').AresLookupResult>('/auth/setup-ares-lookup', { ic }).then((r) => r.data),

  /** Účty z registru plátců DPH (CRPDPH) pro setup wizard (jen dokud nemáme admin usera). */
  setupCrpdphLookup: (dic: string) =>
    api.post<import('./clients').BankLookupResult>('/auth/setup-crpdph-lookup', { dic }).then((r) => r.data),

  /** Sample data generator po setup wizardu (jen pokud DB nemá data). */
  setupSample: () =>
    api.post<SetupSampleResult>(
      '/auth/setup-sample',
    ).then((r) => r.data),

  login: (payload: LoginPayload) =>
    api.post<AuthSessionContract>('/auth/login', payload).then(r => r.data),

  passkeyLoginOptions: (captchaToken?: string) =>
    api.post<WebAuthnFlow>('/auth/webauthn/login/options', {
      cf_turnstile_response: captchaToken,
    }).then(r => r.data),

  passkeyLoginVerify: (flowToken: string, credential: Record<string, any>) =>
    api.post<AuthSessionContract>('/auth/webauthn/login/verify', {
      flow_token: flowToken,
      credential,
    }).then(r => r.data),

  sessionStatus: () =>
    api.get<SessionState>('/auth/session/status').then(r => r.data),
  sessionActivity: () =>
    api.post<SessionState>('/auth/session/activity', {}).then(r => r.data),
  sessionLock: () =>
    api.post<SessionState>('/auth/session/lock', {}).then(r => r.data),
  sessionLockPreference: () =>
    api.get<SessionLockPreference>('/auth/session/lock-preference').then(r => r.data),
  updateSessionLockPreference: (lockAfterMinutes: number | null) =>
    api.put<SessionLockPreferenceUpdate>('/auth/session/lock-preference', {
      lock_after_minutes: lockAfterMinutes,
    }).then(r => r.data),
  sessionUnlockOptions: () =>
    api.post<WebAuthnFlow>('/auth/session/unlock/options', {}).then(r => r.data),
  sessionUnlockVerify: (flowToken: string, credential: Record<string, any>) =>
    api.post<SessionState>('/auth/session/unlock/verify', {
      flow_token: flowToken,
      credential,
    }).then(r => r.data),

  passkeys: () =>
    api.get<{ credentials: PasskeyCredential[] }>('/auth/webauthn/credentials').then(r => r.data.credentials),
  passkeyRegisterOptions: (authorization: { current_password?: string; step_up_token?: string }) =>
    api.post<WebAuthnFlow>('/auth/webauthn/register/options', authorization).then(r => r.data),
  passkeyRegisterVerify: (flowToken: string, credential: Record<string, any>, label: string) =>
    api.post<PasskeyRegistrationResult>('/auth/webauthn/register/verify', {
      flow_token: flowToken, credential, label,
    }).then(r => r.data),
  passkeyRename: (id: number, label: string) =>
    api.patch<{ credential: PasskeyCredential }>(`/auth/webauthn/credentials/${id}`, { label }).then(r => r.data.credential),
  passkeyRevoke: (id: number, stepUpToken: string) =>
    api.delete(`/auth/webauthn/credentials/${id}`, { data: { step_up_token: stepUpToken } }),
  passkeyStepUpOptions: (operation: string) =>
    api.post<WebAuthnFlow>('/auth/webauthn/step-up/options', { operation }).then(r => r.data),
  passkeyStepUpVerify: (flowToken: string, operation: string, credential: Record<string, any>) =>
    api.post<{ step_up_token: string }>('/auth/webauthn/step-up/verify', {
      flow_token: flowToken, operation, credential,
    }).then(r => r.data.step_up_token),
  totpStepUp: (operation: string, code: string) =>
    api.post<{ step_up_token: string }>('/auth/mfa/step-up/totp', { operation, code })
      .then(r => r.data.step_up_token),
  recoveryStepUp: (operation: string, code: string) =>
    api.post<{ step_up_token: string; remaining: number }>('/auth/mfa/step-up/recovery', { operation, code })
      .then(r => r.data),

  /** „Pokračovat bez dvoufázového ověření" — nabídku už server znovu nepošle. */
  dismissMfaOffer: () =>
    api.post<{ dismissed: boolean }>('/auth/mfa/offer/dismiss').then(r => r.data),

  recoveryCodeStatus: () =>
    api.get<RecoveryCodeStatus>('/auth/mfa/recovery-codes').then(r => r.data),
  /** Vrátí kódy v plaintextu — jediná a poslední příležitost, kdy je lze zobrazit. */
  generateRecoveryCodes: (stepUpToken: string) =>
    api.post<RecoveryCodeStatus & { codes: string[] }>('/auth/mfa/recovery-codes', {
      step_up_token: stepUpToken,
    }).then(r => r.data),

  logout: () => api.post('/auth/logout').then(() => undefined),

  me: () => api.get<MeResponse>('/auth/me').then((r) => {
    setCsrfToken(r.data.csrf_token)
    return r.data
  }),

  changePassword: (current: string, next: string) =>
    api.post('/auth/change-password', {
      current_password: current,
      new_password: next,
      new_password_confirm: next,
    }),

  forgot: (email: string, turnstileToken?: string) =>
    api.post('/auth/forgot', {
      email,
      ...(turnstileToken ? { cf_turnstile_response: turnstileToken } : {}),
    }),

  reset: (token: string, password: string) =>
    api.post('/auth/reset', { token, password, password_confirm: password }),

  // TOTP / 2FA
  totpStatus: () => api.get<{ enabled: boolean }>('/auth/totp/status').then(r => r.data),
  totpSetup:  () => api.post<TotpSetup>('/auth/totp/setup').then(r => r.data),
  /**
   * Zapne TOTP.
   *
   * ⚠️ `recovery_codes` chodí PRÁVĚ JEDNOU a jen tomu, kdo ještě žádné
   * použitelné neměl — server plaintext neukládá. Kdo je v tu chvíli
   * nezobrazí, už je nikde nedohledá.
   */
  totpEnable: (code: string) =>
    api.post<{ enabled: boolean; recovery_codes?: string[] } & MfaSetupCompletion>(
      '/auth/totp/enable',
      { code },
    ).then(r => r.data),
}
