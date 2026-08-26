import { api } from './client'

/**
 * Externí integrace — iDoklad, Fakturoid (fáze 2b), Anthropic AI (fáze 2c).
 * Credentials BYOK (Bring Your Own Key), šifrované at-rest přes SecretEncryption.
 */

export interface IdokladCredentialsStatus {
  configured: boolean
  client_id: string | null
}

export interface IdokladCredentialsUpdateResult {
  saved: boolean
  test_ok: boolean
  test_error: string | null
}

export interface ImportJob {
  id: number
  supplier_id: number
  source: 'idoklad' | 'fakturoid' | 'pdf_isdoc_inbox' | 'pdf_ai'
  status: 'queued' | 'running' | 'completed' | 'failed' | 'cancelled'
  params: Record<string, unknown> | null
  total_items: number | null
  processed: number
  created_count: number
  skipped_count: number
  failed_count: number
  current_step: string | null
  log_text: string | null
  last_error: string | null
  cancel_requested: boolean
  started_at: string | null
  finished_at: string | null
  created_by: number
  created_at: string
}

export interface IdokladStartParams {
  include_bank_accounts?: boolean
  include_bank_transactions?: boolean
  include_clients?: boolean
  include_issued?: boolean
  include_received?: boolean
  /** Incremental sync — jen DateLastChange >= idoklad_last_imported_at bookmark */
  incremental?: boolean
  /** Stáhne PDF přílohy (vydané: rendered; přijaté: první PDF attachment od dodavatele) */
  download_attachments?: boolean
  dry_run?: boolean
}

export interface FakturoidCredentialsStatus {
  configured: boolean
  slug: string | null
  email: string | null
  client_id: string | null
  /** 'oauth2' | 'basic' | null — which auth flow is active when configured */
  auth_mode: 'oauth2' | 'basic' | null
  has_oauth: boolean
  has_basic: boolean
}

export interface FakturoidCredentialsUpdateResult {
  saved: boolean
  auth_mode: 'oauth2' | 'basic'
  test_ok: boolean
  test_error: string | null
  account_name: string | null
}

export interface FakturoidCredentialsInput {
  slug: string
  email?: string
  api_key?: string
  client_id?: string
  client_secret?: string
}

export interface FakturoidStartParams {
  include_clients?: boolean
  include_issued?: boolean
  include_received?: boolean
  incremental?: boolean
  download_attachments?: boolean
  dry_run?: boolean
}

export interface AnthropicCredentialsStatus {
  configured: boolean
  default_model: string
  extractions_count: number
  allowed_models: string[]
}

export interface AnthropicCredentialsUpdateResult {
  saved: boolean
  test_ok: boolean
  test_error: string | null
  model: string | null
}

// ── AI provider brána (Epic F7) — 4 provideři za LlmGateway ─────────────────
export type AiProvider = 'anthropic' | 'azure_openai' | 'openai' | 'gemini'
export type AiDataRegion = 'eu' | 'us'

/** Per-provider stav + capability descriptor (whitelist modelů, region). */
export interface AiProviderInfo {
  configured: boolean
  extractions_count: number
  /** whitelist model/deployment id z LlmProviderCapabilities. */
  models: string[]
  default_model: string | null
  data_region: AiDataRegion
  /** true = lze pro tohoto tenanta dosáhnout EU rezidence dat. */
  eu_capable: boolean
  residency_label?: string
  // provider-specific non-secret echo (klíč se NIKDY nevrací — write-only):
  endpoint?: string | null       // azure_openai
  deployment?: string | null     // azure_openai
  api_version?: string | null    // azure_openai
  base_url?: string | null       // openai (EU: eu.api.openai.com)
}

/** Míra uvažování AI. `default` = neposílat providerovi nic navíc. */
export type AiEffort = 'default' | 'fast' | 'accurate'

export interface AiCredentialsResponse {
  ai_provider: AiProvider
  ai_data_region: AiDataRegion
  ai_eu_residency_required: boolean
  /** Volné poznámky firmy připojené k system promptu extrakce. */
  ai_extraction_notes: string
  ai_effort: AiEffort
  ai_efforts: AiEffort[]
  ai_extraction_notes_max: number
  providers: Record<AiProvider, AiProviderInfo>
}

export interface AiTuningPayload {
  ai_extraction_notes?: string
  ai_effort?: AiEffort
}

export interface AiTuningResult {
  saved: boolean
  ai_extraction_notes: string
  ai_effort: AiEffort
}

export interface AiCredentialsPayload {
  provider: AiProvider
  /** WRITE-ONLY — prázdné = zachovat stávající klíč. */
  api_key?: string
  default_model?: string
  // azure_openai
  endpoint?: string
  deployment?: string
  api_version?: string
  // openai
  base_url?: string
}

export interface AiCredentialsUpdateResult {
  saved: boolean
  test_ok: boolean
  test_error: string | null
  model: string | null
}

export interface AiExtractResult {
  ok: boolean
  purchase_invoice_id?: number
  vendor_id?: number
  vendor_name?: string
  document_kind?: 'invoice' | 'receipt' | 'credit_note' | 'advance'
  total_with_vat?: number | null
  currency?: string
  source: 'isdocx' | 'isdoc_embedded' | 'ai' | 'duplicate' | 'ai_failed' | 'ai_invalid' | 'wrong_tenant' | 'no_vendor' | 'create_failed'
  duplicate?: boolean
  message?: string
  // provenance (Epic F7)
  provider?: AiProvider
  region?: AiDataRegion
  model?: string
  usage?: { input_tokens?: number; output_tokens?: number }
  ai_data?: Record<string, unknown>
  error?: string
}

/** Prodejní zrcadlo AiExtractResult — vytváří DRAFT vydané faktury (invoice_id místo purchase_invoice_id). */
export interface AiIssuedExtractResult {
  ok: boolean
  invoice_id?: number
  client_id?: number
  source: 'isdocx' | 'isdoc_embedded' | 'ai' | 'duplicate' | 'ai_failed' | 'ai_invalid' | 'wrong_tenant' | 'no_customer' | 'create_failed' | 'isdoc_map_failed' | 'image_convert_failed'
  provider?: AiProvider
  region?: AiDataRegion
  model?: string
  usage?: { input_tokens?: number; output_tokens?: number }
  ai_data?: Record<string, unknown>
  error?: string
}

export const integrationsApi = {
  // iDoklad credentials
  getIdokladCreds: () =>
    api.get<IdokladCredentialsStatus>('/admin/imports/idoklad/credentials').then(r => r.data),
  setIdokladCreds: (clientId: string, clientSecret: string) =>
    api.put<IdokladCredentialsUpdateResult>('/admin/imports/idoklad/credentials', {
      client_id: clientId, client_secret: clientSecret,
    }).then(r => r.data),
  deleteIdokladCreds: () =>
    api.delete<{ ok: boolean }>('/admin/imports/idoklad/credentials').then(r => r.data),
  startIdoklad: (params: IdokladStartParams = {}) =>
    api.post<{ job_id: number; status: string; params: IdokladStartParams }>(
      '/admin/imports/idoklad/start', params,
    ).then(r => r.data),

  // Fakturoid credentials
  getFakturoidCreds: () =>
    api.get<FakturoidCredentialsStatus>('/admin/imports/fakturoid/credentials').then(r => r.data),
  setFakturoidCreds: (input: FakturoidCredentialsInput) =>
    api.put<FakturoidCredentialsUpdateResult>('/admin/imports/fakturoid/credentials', input).then(r => r.data),
  deleteFakturoidCreds: () =>
    api.delete<{ ok: boolean }>('/admin/imports/fakturoid/credentials').then(r => r.data),
  startFakturoid: (params: FakturoidStartParams = {}) =>
    api.post<{ job_id: number; status: string; params: FakturoidStartParams }>(
      '/admin/imports/fakturoid/start', params,
    ).then(r => r.data),

  // Anthropic Claude
  getAnthropicCreds: () =>
    api.get<AnthropicCredentialsStatus>('/admin/imports/anthropic/credentials').then(r => r.data),
  setAnthropicCreds: (apiKey: string, defaultModel = 'claude-haiku-4-5') =>
    api.put<AnthropicCredentialsUpdateResult>('/admin/imports/anthropic/credentials', {
      api_key: apiKey, default_model: defaultModel,
    }).then(r => r.data),
  deleteAnthropicCreds: () =>
    api.delete<{ ok: boolean }>('/admin/imports/anthropic/credentials').then(r => r.data),

  // AI provider brána (Epic F7) — per-provider credentials, admin-only, klíč write-only
  getAiCredentials: () =>
    api.get<AiCredentialsResponse>('/admin/imports/ai/credentials').then(r => r.data),
  setAiCredentials: (payload: AiCredentialsPayload) =>
    api.put<AiCredentialsUpdateResult>('/admin/imports/ai/credentials', payload).then(r => r.data),
  deleteAiCredentials: (provider: AiProvider) =>
    api.delete<{ ok: boolean }>('/admin/imports/ai/credentials', { params: { provider } }).then(r => r.data),
  setAiTuning: (payload: AiTuningPayload) =>
    api.put<AiTuningResult>('/admin/imports/ai/tuning', payload).then(r => r.data),
  testAiConnection: (provider: AiProvider) =>
    api.post<AiCredentialsUpdateResult>('/admin/imports/ai/credentials/test', { provider }).then(r => r.data),
  extractPdfAi: (file: File, model?: string, importBatchId?: string) => {
    const fd = new FormData()
    fd.append('pdf', file, file.name)
    const qs = new URLSearchParams()
    if (model) qs.set('model', model)
    if (importBatchId) qs.set('import_batch_id', importBatchId)
    const url = qs.toString() ? `/admin/imports/ai-extract-pdf?${qs.toString()}` : '/admin/imports/ai-extract-pdf'
    return api.post<AiExtractResult>(url, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
      timeout: 120000, // 2 min — AI inference může trvat
    }).then(r => r.data)
  },
  // Prodejní zrcadlo — AI import VYDANÉ faktury (draft vydané faktury k revizi v editoru).
  extractPdfAiIssued: (file: File, model?: string) => {
    const fd = new FormData()
    fd.append('pdf', file, file.name)
    const url = model ? `/admin/imports/ai-extract-pdf-issued?model=${model}` : '/admin/imports/ai-extract-pdf-issued'
    return api.post<AiIssuedExtractResult>(url, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
      timeout: 120000, // 2 min — AI inference může trvat
    }).then(r => r.data)
  },

  // Shared job tracking
  getJob: (id: number, signal?: AbortSignal) =>
    api.get<ImportJob>(`/admin/imports/${id}`, { signal }).then(r => r.data),
  cancelJob: (id: number) =>
    api.post<{ ok: boolean; cancel_requested: boolean }>(`/admin/imports/${id}/cancel`).then(r => r.data),
  deleteJob: (id: number) =>
    api.delete<{ ok: boolean; deleted: boolean }>(`/admin/imports/${id}`).then(r => r.data),
}
