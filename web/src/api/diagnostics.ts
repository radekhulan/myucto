import { api } from './client'

/** Výsledek jedné kontroly prostředí. Popisky se skládají v UI podle `id`. */
export interface DiagnosticCheck {
  /** Stabilní identifikátor pravidla — klíč do i18n (`diagnostics.checks.<id>.*`). */
  id: string
  status: 'ok' | 'warn' | 'fail' | 'skip'
  /** Naměřená hodnota, už zformátovaná pro člověka. */
  actual: string
  /** Co se očekává. */
  expected: string
  /** Zjištění, které není nález — ukazuje se i u kontroly, která dopadla dobře. */
  info?: string
  /** Kapitola manuálu s nápravou (`/manual?ch=…`). */
  manual: string
  /** Jiná podoba téhož nálezu s vlastními texty (`diagnostics.checks.<id>.variants.<variant>`). */
  variant?: string
  meta?: Record<string, unknown>
}

export interface DiagnosticsSummary {
  status: 'ok' | 'warn' | 'fail'
  ok: number
  warn: number
  fail: number
  skip: number
}

export interface DiagnosticsReport {
  generated_at: string
  summary: DiagnosticsSummary
  checks: DiagnosticCheck[]
  facts: Record<string, any>
  imported_posting_ambiguities: ImportedPostingAmbiguities
}

export interface ImportedPostingAmbiguities {
  available: boolean
  supplier_id: number
  total: number | null
  per_page: number
  candidate_limit: number
  next_after: number | null
  entries: {
    entry_id: number
    entry_date: string
    description: string
    owner_count: number
    candidates: { doc_type: 'invoice' | 'purchase_invoice'; doc_id: number; document_number: string | null }[]
  }[]
}

/**
 * Audit prostředí před prvním setupem. Oproti `DiagnosticsReport` nenese
 * naměřená fakta (endpoint je na neinicializované instalaci veřejný) a přidává
 * druh instalace, aby šlo poradit nápravu pro Docker i nativní běh.
 */
export interface PreflightReport {
  generated_at: string
  environment: 'docker' | 'native' | string
  summary: DiagnosticsSummary
  checks: DiagnosticCheck[]
}

/** Rozsah balíčku. `include_logs` je vždy vědomá volba uživatele. */
export interface BundleOptions {
  include_version?: boolean
  include_environment?: boolean
  include_license?: boolean
  include_migrations?: boolean
  include_cron?: boolean
  include_config?: boolean
  include_logs?: boolean
  days?: number
  log_level?: string
}

export interface BundleItem {
  name: string
  kind: string
  bytes: number
  /** true = položka může obsahovat osobní údaje (dnes výhradně logy). */
  sensitive: boolean
}

export interface BundlePreview {
  items: BundleItem[]
  total_bytes: number
  within_limit: boolean
  max_bytes: number
  options: Required<BundleOptions>
  log_days: string[]
}

export interface BundleResult {
  ok: boolean
  error: string | null
  filename: string | null
  bytes: number | null
  sha256: string | null
  items: BundleItem[] | null
}

export interface LogPreview {
  day: string | null
  days: string[]
  page: number
  per_page: number
  total: number
  lines: string[]
  truncated: boolean
}

/** Volby přešifrování mzdového archivu. Zápis vyžaduje `confirm`. */
export interface PayrollArchiveReencryptOptions {
  dry_run?: boolean
  include_orphans?: boolean
  purge_erased?: boolean
  confirm?: boolean
  confirm_purge?: boolean
}

/** Výsledek jedné dávky přešifrování (server zpracuje nejvýš ~200 souborů). */
export interface PayrollArchiveReencryptResult {
  dry_run: boolean
  processed: number
  remaining: number
  counts: Record<string, number>
  problems: { supplier_id: number; storage_key: string; status: string }[]
}

/** Výsledek jedné dávky přebalení na aktuální klíč. */
export interface PayrollKeyRewrapResult {
  dry_run: boolean
  rewrapped: number
  would_rewrap: number
  failed: number
  remaining: number
  unknown: number
}

export const diagnosticsApi = {
  /** Přešifruje dávku nešifrovaných mzdových dokumentů (Diagnostika). */
  payrollArchiveReencrypt: (payload: PayrollArchiveReencryptOptions) =>
    api
      .post<PayrollArchiveReencryptResult>('/admin/diagnostics/payroll-archive/reencrypt', payload)
      .then((r) => r.data),

  /** Přebalí dávku mzdových šifrovaných hodnot na aktuální klíč. */
  payrollKeyRewrap: (payload: { dry_run?: boolean; confirm?: boolean }) =>
    api
      .post<PayrollKeyRewrapResult>('/admin/diagnostics/payroll-archive/rewrap', payload)
      .then((r) => r.data),
  /** Úplné měření klíčů mzdových dat; výsledek jde do cache Diagnostiky. */
  payrollKeyMeasure: () =>
    api
      .post<{ check: DiagnosticCheck }>('/admin/diagnostics/payroll-archive/measure', {})
      .then((r) => r.data.check),

  /**
   * Kontrola prostředí před prvním setupem. Veřejná, ale jen dokud instalace
   * nemá admina — po setupu vrací 409 a platí `report()`.
   */
  preflight: (signal?: AbortSignal) =>
    api.get<PreflightReport>('/auth/setup-preflight', { signal }).then((r) => r.data),

  /** Systém → Diagnostika: audit prostředí s verdiktem. */
  report: (signal?: AbortSignal, postingAfter = 0) =>
    api.get<DiagnosticsReport>('/admin/diagnostics', { signal, params: { posting_after: postingAfter } }).then((r) => r.data),

  /** Co přesně bude v balíčku, položku po položce, včetně velikostí. */
  preview: (params: BundleOptions, signal?: AbortSignal) =>
    api.get<BundlePreview>('/admin/diagnostics/bundle/preview', { params, signal }).then((r) => r.data),

  /** Stránkovaný náhled výřezu logu — uživatel si musí obsah moci prohlédnout. */
  logs: (
    params: { day?: string; days?: number; level?: string; page?: number; per_page?: number },
    signal?: AbortSignal,
  ) => api.get<LogPreview>('/admin/diagnostics/logs', { params, signal }).then((r) => r.data),

  /** Sestaví ZIP na disku instalace. Nikam ho neodesílá. */
  create: (payload: BundleOptions) =>
    api.post<BundleResult>('/admin/diagnostics/bundle', payload).then((r) => r.data),

  /** URL ke stažení hotového balíčku (prohlížeč si ho stáhne přímo). */
  downloadUrl: (filename: string) =>
    `/api/admin/diagnostics/bundle/download?file=${encodeURIComponent(filename)}`,
}
