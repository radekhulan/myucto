import { api } from './client'

/**
 * Stažení automatických záloh (cron-backup*.php).
 *
 * Endpointy jsou pod `/api/admin/`, takže je middleware pouští jen superadminovi —
 * dump databáze nezná hranice firem.
 *
 * Heslo k šifrovaným ZIPům se nevrací v přehledu: chce se pro něj čerstvé ověření
 * (passkey, nebo heslo + TOTP) a odpověď se nikde necachuje.
 */

export type BackupKind = 'database' | 'documents' | 'pdf' | 'payroll' | 'other'

export interface BackupFile {
  name: string
  kind: BackupKind
  size_bytes: number
  /** Čas poslední změny souboru na disku. */
  modified_at: string
  /** Čas z názvu souboru — spolehlivější než mtime, ale jen u souborů v konvenci. */
  taken_at: string | null
}

export interface BackupSection {
  kind: BackupKind
  /** Jen nejnovější zálohy sekce (`latest_per_kind`), od nejnovější. */
  files: BackupFile[]
  /** Počet a velikost všech záloh sekce na disku, nejen vypsaných. */
  total_files: number
  size_bytes: number
}

export interface BackupOverview {
  directory: string
  exists: boolean
  /** Jsou ZIPy šifrované (cfg cron.backup.password)? Řídí nabídku odhalení hesla. */
  encrypted: boolean
  retention: string
  kinds: BackupKind[]
  latest_per_kind: number
  sections: BackupSection[]
  total_files: number
  total_size_bytes: number
}

export interface BackupPasswordProof {
  password?: string
  totp_code?: string
  step_up_token?: string
}

export interface BackupPassword {
  encrypted: boolean
  password: string | null
  cipher?: string
}

export const backupsApi = {
  overview: () => api.get<BackupOverview>('/admin/backups').then(r => r.data),
  download: (name: string) =>
    api.get<Blob>(`/admin/backups/download/${encodeURIComponent(name)}`, { responseType: 'blob' }),
  revealPassword: (proof: BackupPasswordProof) =>
    api.post<BackupPassword>('/admin/backups/password', proof).then(r => r.data),
}
