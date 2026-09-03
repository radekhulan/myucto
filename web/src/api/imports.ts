import { api } from './client'

export type ImportKind = 'auto' | 'issued' | 'purchase'

/**
 * Stav, ve kterém mají vzniknout PŘIJATÉ doklady. Koncept se nezapočítává do nákladů,
 * závazků ani do výkazů, takže dávková migrace z jiného systému dává smysl jen jako
 * `received` — jinak by účetní musela stovky dokladů otevřít jeden po druhém.
 */
export type PurchaseImportStatus = 'received' | 'draft'

export interface ImportResultRow {
  file: string
  status: 'created' | 'skipped' | 'failed'
  /** Doklad už v systému byl — status zůstává 'created' (v systému je), ale nevznikl teď. */
  duplicate?: boolean
  reason?: string
  kind?: 'issued' | 'purchase' | null   // backend dispatch route (auto → konkrétní)
  invoice_id?: number          // pro issued
  purchase_invoice_id?: number  // pro purchase
  client_id?: number
  client_created?: boolean
  vendor_id?: number
  project_id?: number | null
  varsymbol?: string
  imported_status?: 'paid' | 'issued'

  // ── Vysvětlivky k dokladu ────────────────────────────────────────────────
  // Doklad rozstřelený dřív, než se vůbec dostal k řádkům (nečitelný soubor,
  // cizí IČO, výjimka), nese jen `reason` — proto je všechno níž volitelné
  // a čte se přes `?? []` / `?? 0`.
  /** Co jsme dopočítali nebo přepočetli (kurz, ceny včetně DPH, zařazení do OSS). */
  notes?: string[]
  /** Co si uživatel musí ověřit ručně. Chodí i u úspěšně vytvořeného dokladu. */
  warnings?: string[]
  varsymbol_substituted?: boolean
  /** Číslo dokladu ze zdrojového systému — jediný údaj, pod kterým ho tam najde. */
  document_number?: string | null

  // Per-doklad čítače řádků posílá backend jen u status = 'created'.
  oss_items?: number
  oss_rate_type_unknown?: number
  oss_manual_review?: number
  oss_credit_note_pending_period?: number
}

/**
 * Souhrn počítá backend, ne frontend: kdyby si ho tabulka sečetla z `results`,
 * rozešel by se s tím, co se opravdu zapsalo.
 */
export interface ImportSummary {
  created: number
  /** Doklad v souboru už v systému byl — do `created` se nepočítá, jinak by opakovaná dávka hlásila stovky „vytvořených". */
  duplicates?: number
  skipped: number
  failed: number
  /** ŘÁDKŮ zařazených do OSS (jen z vytvořených dokladů). */
  oss_items?: number
  /** Z toho řádků bez typu sazby — bez ručního doplnění se do OSS podání nedostanou. */
  oss_rate_type_unknown?: number
  /**
   * ŘÁDKŮ, u nichž systém neurčil místo plnění (sazba platí v zemi dodavatele i ve státě
   * spotřeby, nebo se číselníku nedalo zeptat). Takové řádky JSOU OSS a jsou tedy
   * započítané i v `oss_items` — kategorie se PŘEKRÝVAJÍ, nejsou disjunktní.
   */
  oss_manual_review?: number
  /** DOKLADŮ typu dobropis s OSS řádkem bez původního období. */
  oss_credit_notes_pending_period?: number
  /** DOKLADŮ s dosazeným variabilním symbolem (i přeskočených a odmítnutých). */
  varsymbol_substituted?: number
  /** DOKLADŮ s aspoň jedním varováním. */
  with_warnings?: number
}

export interface ImportReport {
  summary: ImportSummary
  results: ImportResultRow[]
  /** Běh na pozadí zrušil uživatel — doklady založené do té chvíle v systému zůstávají. */
  cancelled?: boolean
  /** Kolik dokladů z dávky se po zrušení nezpracovalo. */
  not_processed?: number
}

/**
 * Stav importu běžícího na pozadí. Tvar sdílí s ostatními `import_jobs` (iDoklad,
 * Fakturoid, dokumenty) — jde o tutéž tabulku i tentýž worker.
 */
export interface FileImportJob {
  id: number
  status: 'queued' | 'running' | 'completed' | 'completed_with_warnings' | 'failed' | 'cancelled'
  total_items: number | null
  processed: number
  created_count: number
  skipped_count: number
  failed_count: number
  current_step: string | null
  log_text: string | null
  last_error: string | null
}

export interface StartedImportJob {
  job_id: number
  status: string
  files: number
  kind: ImportKind
}

/**
 * Spustí import na pozadí. Dávka z jiného systému má běžně tisíce dokladů —
 * synchronní {@link uploadImport} na ni nestačí a její utnutí uprostřed nechá doklady
 * založené, ale nedorovná číselné řady ani nepřepočte statistiky klientů.
 */
export async function startImportJob(
  files: File[],
  kind: ImportKind = 'auto',
  purchaseStatus: PurchaseImportStatus = 'received',
): Promise<StartedImportJob> {
  const fd = new FormData()
  for (const f of files) fd.append('files[]', f, f.name)
  const r = await api.post<StartedImportJob>(
    `/admin/import/start?kind=${kind}&purchase_status=${purchaseStatus}`, fd,
    { headers: { 'Content-Type': 'multipart/form-data' } },
  )
  return r.data
}

export async function fetchImportJob(id: number): Promise<FileImportJob> {
  const r = await api.get<FileImportJob>(`/admin/imports/${id}`)
  return r.data
}

/** Report se stahuje jednou po doběhnutí — u tisíců dokladů má megabajty. */
export async function fetchImportJobReport(id: number): Promise<ImportReport> {
  const r = await api.get<ImportReport>(`/admin/imports/${id}/report`)
  return r.data
}

export async function cancelImportJob(id: number): Promise<void> {
  await api.post(`/admin/imports/${id}/cancel`, {})
}

/**
 * Upload import s explicit kind:
 *   - 'auto'     (default) — per-soubor detekce dle IČO buyer/supplier
 *   - 'issued'   — vynutí issued route (vydané faktury)
 *   - 'purchase' — vynutí purchase route (přijaté faktury)
 */
export async function uploadImport(files: File[], kind: ImportKind = 'auto'): Promise<ImportReport> {
  const fd = new FormData()
  for (const f of files) fd.append('files[]', f, f.name)
  const r = await api.post<ImportReport>(`/admin/import?kind=${kind}`, fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return r.data
}
