import { api } from './client'
import { uploadChunked, type ChunkedUploadProgress } from './chunkedUpload'

/**
 * Upozornění náhledu převodu, že cílová firma je nezisková organizace nebo SVJ
 * (`NonprofitMigrationNotice` na serveru). Běžná firma ho v odpovědi nedostane.
 */
export interface MigrationNonprofitNotice {
  level: 'warning'
  code: 'migration_nonprofit_untested'
  message: string
  context?: { entity_profile: 'nonprofit' | 'svj' | 'unknown'; framework_504: boolean }
}

/**
 * Společný klient průvodců převodem z cizího programu (Money S3, POHODA, PREMIER).
 * Server pod `{base}` nabízí nahrání po částech, stav nahraného souboru, spuštění převodu
 * na pozadí a protokoly běhů; zdroj si ke klientovi přidá vlastní volání.
 */
export function createMigrationApi<TUpload, TPending, TRun, TStart>(base: string, config: { showTimeout?: number } = {}) {
  return {
    /** Soubor se posílá po částech (chunkedUpload.ts); po poslední části ho server zpracuje na pozadí. */
    uploadChunked: (file: File, onProgress?: ChunkedUploadProgress, onStarted?: (token: string) => void): Promise<{ token: string; job_id: number | null }> =>
      uploadChunked(base, file, onProgress, onStarted),
    /**
     * Stav a náhled nahraného souboru. `retry` zopakuje selhanou přípravu náhledu na serveru.
     * Zdroj, jehož server náhled jen čte (těžkou práci dělá job na pozadí), si dá kratší
     * `showTimeout`: visící požadavek průvodce pak ukáže jako chybu se „Zkusit znovu".
     */
    show: (token: string, options?: { retry?: boolean }): Promise<TUpload | TPending> =>
      api.get<TUpload | TPending>(`${base}/uploads/${token}`, {
        ...(config.showTimeout ? { timeout: config.showTimeout } : {}),
        ...(options?.retry ? { params: { retry: 1 } } : {}),
      }).then(r => r.data),
    start: (token: string, params: TStart): Promise<{ job_id: number; status: string; mode: string }> =>
      api.post(`${base}/uploads/${token}/start`, params).then(r => r.data),
    runs: (): Promise<{ items: TRun[] }> =>
      api.get<{ items: TRun[] }>(`${base}/runs`).then(r => r.data),
    run: (id: number): Promise<TRun> =>
      api.get<TRun>(`${base}/runs/${id}`).then(r => r.data),
    /** Jen doběhlá zkouška nanečisto; protokol ostrého převodu API smazat nedovolí. */
    deleteRun: (id: number): Promise<{ ok: boolean }> =>
      api.delete<{ ok: boolean }>(`${base}/runs/${id}`).then(r => r.data),
  }
}
