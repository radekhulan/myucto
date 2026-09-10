import { api } from './client'

export type DocType = 'pdf' | 'docx' | 'xlsx' | 'xml' | 'zfo' | 'p7s' | 'zip' | 'image' | 'other'
export type EntityType = 'client' | 'invoice' | 'purchase_invoice' | 'project' | 'journal_entry' | 'bank_transaction' | 'cash_document'

/** Viditelnost dokladu v rámci tenanta (Epic F7). company = celá firma, user = jen vlastník. */
export type DocScope = 'company' | 'user'
/** Role souboru u N-souborů-na-doklad (Epic F7). */
export type DocFileRole = 'primary' | 'attachment'

export interface DocumentFile {
  id: number
  document_id: number
  role: DocFileRole
  sha256: string
  filename: string
  original_name: string | null
  mime_type: string | null
  size_bytes: number | null
  doc_type: DocType | null
  sort_order: number
  uploaded_by: number | null
  created_at: string
}

export interface DocFolder {
  id: number
  parent_id: number | null
  name: string
  created_at: string
  subfolder_count: number
  file_count: number
  total_bytes: number
}

export interface DocLink {
  entity_type: EntityType
  entity_id: number
  label: string
}

export interface DmsMessage {
  dm_id: string | null
  direction: 'sent' | 'received' | 'unknown'
  sender_box_id: string | null
  sender_name: string | null
  sender_address: string | null
  recipient_box_id: string | null
  recipient_name: string | null
  recipient_address: string | null
  annotation: string | null
  sender_ref_number: string | null
  recipient_ref_number: string | null
  dm_type: string | null
  dm_status: string | null
  delivery_time: string | null
  acceptance_time: string | null
}

export interface DocItem {
  id: number
  supplier_id: number
  folder_id: number | null
  title: string
  description: string | null
  original_name: string
  sha256: string
  mime_type: string
  size_bytes: number
  doc_type: DocType
  source: 'manual' | 'zfo_extract' | 'zip_extract'
  parent_document_id: number | null
  signature_for_id: number | null
  text_status: string
  thumb_status: string
  has_thumb: boolean
  created_at: string
  deleted_at: string | null
  // scope/owner (Epic F7) — optional pro back-compat se staršími payloady
  scope?: DocScope
  owner_user_id?: number | null
  owner_name?: string | null
  // detail-only
  tags?: string[]
  links?: DocLink[]
  attachments?: DocItem[]
  files?: DocumentFile[]
  dms_message?: DmsMessage | null
  breadcrumb?: BreadcrumbItem[]
}

export interface BreadcrumbItem { id: number; name: string }

/** Stránkovací meta (Support\Pagination) + doprovodná data k výpisu složky. */
export interface DocListMeta {
  total: number
  page: number
  per_page: number
  pages: number
  breadcrumb: BreadcrumbItem[]
  folders: DocFolder[]
  max_file_bytes: number
  php_max_upload_bytes?: number
  tag?: string
}

export interface FolderListing {
  data: DocItem[]
  meta: DocListMeta
}

export interface TrashMeta {
  total: number
  page: number
  per_page: number
  pages: number
  folders: DocFolder[]
}

export interface TrashListing {
  data: DocItem[]
  meta: TrashMeta
}

export interface LinkSearchResult {
  entity_type: EntityType
  entity_id: number
  label: string
  sublabel: string
  meta: string
}

export interface TagInfo { id: number; name: string; usage_count: number }

/** Položka přeskočeného/chybného souboru při uploadu (name = cesta v ZIP/složce, reason = kód). */
export interface UploadSkip {
  name: string
  reason: string
}

/** Výsledek synchronního uploadu — kolik vzniklo + co se nenahrálo. */
export interface UploadResult {
  created: number
  /** Dokumenty nejvyšší úrovně (u ZFO obálka) — ty se navazují na doklad. */
  root_ids?: number[]
  skipped: UploadSkip[]
  errors: UploadSkip[]
}

export interface DocJob {
  id: number
  source: 'document_zip_import' | 'document_zip_export'
  status: 'queued' | 'running' | 'completed' | 'failed' | 'cancelled'
  total_items: number | null
  processed: number
  created_count: number
  skipped_count: number
  failed_count: number
  current_step: string | null
  last_error: string | null
  cancel_requested: boolean
  result_name: string | null
  result_size: number | null
  created_at: string
  finished_at: string | null
}

function sid(): string {
  const s = localStorage.getItem('myinvoice.current_supplier_id')
  return s && /^\d+$/.test(s) ? s : ''
}
function urlWith(path: string, params: Record<string, string> = {}): string {
  const qs = new URLSearchParams(params)
  const s = sid()
  if (s) qs.set('supplier_id', s)
  const q = qs.toString()
  return `/api${path}${q ? '?' + q : ''}`
}

export const documentsApi = {
  list: (
    folderId: number | null,
    opts: {
      docType?: string; tag?: string; scope?: DocScope; ownerUserId?: number | null
      page?: number; perPage?: number
    } = {},
  ) =>
    api.get<FolderListing>('/documents', {
      params: {
        folder_id: folderId ?? '',
        ...(opts.docType ? { doc_type: opts.docType } : {}),
        ...(opts.tag ? { tag: opts.tag } : {}),
        ...(opts.scope ? { scope: opts.scope } : {}),
        ...(opts.ownerUserId != null ? { owner_user_id: opts.ownerUserId } : {}),
        ...(opts.page ? { page: opts.page } : {}),
        ...(opts.perPage ? { per_page: opts.perPage } : {}),
      },
    }).then(r => r.data),

  get: (id: number) => api.get<DocItem>(`/documents/${id}`).then(r => r.data),

  search: (q: string) =>
    api.get<{ documents: DocItem[]; query: string }>('/documents/search', { params: { q } })
      .then(r => r.data.documents),

  linkSearch: (q: string, types?: EntityType[]) =>
    api.get<{ results: LinkSearchResult[] }>('/documents/link-search', {
      params: { q, ...(types ? { types: types.join(',') } : {}) },
    }).then(r => r.data.results),

  byEntity: (type: EntityType, id: number) =>
    api.get<{ documents: DocItem[] }>(`/documents/by-entity/${type}/${id}`).then(r => r.data.documents),

  tags: () => api.get<{ tags: TagInfo[] }>('/documents/tags').then(r => r.data.tags),

  update: (id: number, payload: { title?: string; description?: string | null; tags?: string[] }) =>
    api.patch<DocItem>(`/documents/${id}`, payload).then(r => r.data),

  move: (id: number, folderId: number | null) =>
    api.post<{ ok: boolean }>(`/documents/${id}/move`, { folder_id: folderId }).then(r => r.data),

  remove: (id: number) => api.delete<{ ok: boolean }>(`/documents/${id}`).then(r => r.data),
  restore: (id: number) => api.post<{ ok: boolean }>(`/documents/${id}/restore`).then(r => r.data),

  addLink: (id: number, entityType: EntityType, entityId: number) =>
    api.post<{ links: DocLink[] }>(`/documents/${id}/links`, { entity_type: entityType, entity_id: entityId })
      .then(r => r.data.links),
  removeLink: (id: number, entityType: EntityType, entityId: number) =>
    api.delete<{ links: DocLink[] }>(`/documents/${id}/links`, {
      params: { entity_type: entityType, entity_id: entityId },
    }).then(r => r.data.links),

  trash: (opts: { page?: number; perPage?: number } = {}) =>
    api.get<TrashListing>('/documents/trash', {
      params: {
        ...(opts.page ? { page: opts.page } : {}),
        ...(opts.perPage ? { per_page: opts.perPage } : {}),
      },
    }).then(r => r.data),
  // `kept_documents` = doklady, které v koši zůstaly kvůli živé vazbě z jiné agendy
  // (mzdy, exekuce, podání na FS) — vysypání se kvůli nim nezastaví, jen je vynechá.
  emptyTrash: () => api.post<{
    ok: boolean
    deleted: number
    kept: number
    kept_documents: { id: number; title: string; reason: string }[]
  }>('/documents/trash/empty').then(r => r.data),

  bulk: (action: 'move' | 'delete' | 'tag', ids: number[], extra: Record<string, unknown> = {}) =>
    api.post<{ ok: boolean; affected: number }>('/documents/bulk', { action, ids, ...extra }).then(r => r.data),

  upload: (
    files: File[],
    opts: { folderId?: number | null; zipMode?: 'explode' | 'keep'; relpaths?: string[] } = {},
    onProgress?: (pct: number) => void,
  ) => {
    const fd = new FormData()
    for (const f of files) fd.append('file[]', f, f.name)
    if (opts.folderId != null) fd.append('folder_id', String(opts.folderId))
    fd.append('zip_mode', opts.zipMode ?? 'keep')
    if (opts.relpaths) for (const p of opts.relpaths) fd.append('relpaths[]', p)
    return api.post<UploadResult>('/documents', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: (e) => {
        if (onProgress && e.total) onProgress(Math.round((e.loaded / e.total) * 100))
      },
    }).then(r => r.data)
  },

  // Folders
  allFolders: () => api.get<{ folders: DocFolder[] }>('/document-folders').then(r => r.data.folders),
  createFolder: (name: string, parentId: number | null) =>
    api.post<{ id: number }>('/document-folders', { name, parent_id: parentId }).then(r => r.data),
  renameFolder: (id: number, name: string) =>
    api.patch<{ ok: boolean }>(`/document-folders/${id}`, { name }).then(r => r.data),
  moveFolder: (id: number, parentId: number | null) =>
    api.post<{ ok: boolean }>(`/document-folders/${id}/move`, { parent_id: parentId }).then(r => r.data),
  deleteFolder: (id: number) => api.delete<{ ok: boolean }>(`/document-folders/${id}`).then(r => r.data),
  restoreFolder: (id: number) => api.post<{ ok: boolean }>(`/document-folders/${id}/restore`).then(r => r.data),

  // ── Background joby (ZIP import/export) ──
  zipImport: (file: File, folderId: number | null) => {
    const fd = new FormData()
    fd.append('file', file, file.name)
    if (folderId != null) fd.append('folder_id', String(folderId))
    return api.post<{ job_id: number; status: string }>('/documents/zip-import', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  exportZip: (ids: number[], folderIds: number[] = []) =>
    api.post<{ job_id: number; status: string }>('/documents/export', { ids, folder_ids: folderIds }).then(r => r.data),
  // Chunkovaný upload (obchází PHP post_max_size) — velký ZIP / složka / velký soubor
  uploadStart: (mode: 'zip-explode' | 'folder' | 'single', folderId: number | null, name?: string) =>
    api.post<{ job_id: number; mode: string }>('/documents/upload/start', {
      mode, folder_id: folderId, ...(name ? { name } : {}),
    }).then(r => r.data),
  uploadChunkBytes: (jobId: number, chunk: Blob) =>
    api.post<{ size: number }>(`/documents/upload/chunk-bytes?job_id=${jobId}`, chunk, {
      headers: { 'Content-Type': 'application/octet-stream' },
    }).then(r => r.data),
  uploadChunkFiles: (jobId: number, files: File[], relpaths: string[]) => {
    const fd = new FormData()
    for (const f of files) fd.append('file[]', f, f.name)
    for (const p of relpaths) fd.append('relpaths[]', p)
    return api.post<{ added: number }>(`/documents/upload/chunk-files?job_id=${jobId}`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  uploadFinish: (jobId: number) =>
    api.post<{ job_id: number }>('/documents/upload/finish', { job_id: jobId }).then(r => r.data),

  jobs: (signal?: AbortSignal) =>
    api.get<{ jobs: DocJob[] }>('/documents/jobs', { signal }).then(r => r.data.jobs),
  job: (id: number) => api.get<DocJob>(`/documents/jobs/${id}`).then(r => r.data),
  cancelJob: (id: number) => api.post<{ ok: boolean }>(`/documents/jobs/${id}/cancel`).then(r => r.data),
  deleteJob: (id: number) => api.delete<{ ok: boolean }>(`/documents/jobs/${id}`).then(r => r.data),
  jobDownloadUrl: (id: number) => urlWith(`/documents/jobs/${id}/download`),

  // ── N-souborů-na-doklad (Epic F7) — primary + attachments ──
  listFiles: (id: number) =>
    api.get<{ files: DocumentFile[] }>(`/documents/${id}/files`).then(r => r.data.files ?? []),
  addFiles: (id: number, files: File[], onProgress?: (pct: number) => void) => {
    const fd = new FormData()
    for (const f of files) fd.append('file[]', f, f.name)
    return api.post<{ files: DocumentFile[] }>(`/documents/${id}/files`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: (e) => { if (onProgress && e.total) onProgress(Math.round((e.loaded / e.total) * 100)) },
    }).then(r => r.data)
  },
  /** set-primary / změna pořadí (sort_order). */
  patchFile: (id: number, fileId: number, payload: { role?: DocFileRole; sort_order?: number }) =>
    api.patch<{ files: DocumentFile[] }>(`/documents/${id}/files/${fileId}`, payload).then(r => r.data),
  deleteFile: (id: number, fileId: number) =>
    api.delete<{ files: DocumentFile[] }>(`/documents/${id}/files/${fileId}`).then(r => r.data),
  fileDownloadUrl: (id: number, fileId: number) => urlWith(`/documents/${id}/files/${fileId}/download`),

  previewBytes: (id: number) =>
    api.get<ArrayBuffer>(`/documents/${id}/download`, { responseType: 'arraybuffer' }).then(r => r.data),

  // Direct-navigation URLs (browser; supplier_id via query param)
  previewUrl: (id: number) => urlWith(`/documents/${id}/preview`),
  downloadUrl: (id: number) => urlWith(`/documents/${id}/download`),
  thumbUrl: (id: number) => urlWith(`/documents/${id}/thumb`),
  bulkDownloadUrl: (ids: number[]) => urlWith('/documents/bulk-download', { ids: ids.join(',') }),
}
